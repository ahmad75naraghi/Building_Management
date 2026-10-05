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

    /** شرح آخرین نتیجهٔ ارسال — برای ابزار تشخیص */
    private string $lastDetail = '';

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
     * @param string   $to       شماره مقصد (فرمت 09xxxxxxxxx)
     * @param string   $text     متن اصلی پیامک
     * @param array    $args     آرگومان‌های پترن (متناظر با arg1 و arg2 و ...)
     * @param int|null $bodyId   شناسه پترن (اگر نال باشد، پترن پیش‌فرض استفاده می‌شود)
     * @param bool     $immediate اگر درست باشد پیامک در صف نمی‌رود و همان لحظه ارسال می‌شود (مثل کد ورود)
     */
    public function sendByBaseNumber(string $to, string $text, array $args = [], ?int $bodyId = null, bool $immediate = false): bool
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

        // حالت صف: ارسال به کار پس‌زمینه سپرده می‌شود تا درخواست کاربر
        // منتظر سامانه پیامک نماند (فعال‌سازی با QUEUE_DRIVER=database).
        // پیام‌های فوری مثل کد ورود از صف رد نمی‌شوند چون کاربر منتظر همان لحظه است.
        if (!$immediate && $this->queueEnabled()) {
            $jobId = JobQueue::enqueue('sms', [
                'to' => $to,
                'text' => $text,
                'args' => array_values($args),
                'body_id' => $bodyId,
            ]);
            if ($jobId !== null) {
                Logger::info('SmsService', 'پیامک در صف پس‌زمینه ثبت شد', ['to' => $to, 'job_id' => $jobId]);
                return true; // پذیرفته شد؛ ارسال واقعی توسط پردازشگر صف انجام می‌شود
            }
            // جدول صف موجود نبود → ارسال همگام مثل قبل
        }

        return $this->sendDirect($to, $text, $args, $bodyId);
    }

    /** آیا صف پس‌زمینه برای پیامک فعال است؟ */
    private function queueEnabled(): bool
    {
        $driver = (string) \App\Config\AppConfig::env('QUEUE_DRIVER', 'sync');
        return $driver === 'database' && JobQueue::isAvailable();
    }

    /**
     * ارسال واقعی پیامک (همگام). توسط پردازشگر صف نیز مستقیم فراخوانی
     * می‌شود تا حلقهٔ صف ایجاد نشود.
     *
     * @param string   $to     شماره مقصد (فرمت 09xxxxxxxxx)
     * @param string   $text   متن اصلی پیامک
     * @param array    $args   آرگومان‌های پترن (متناظر با arg1 و arg2 و ...)
     * @param int|null $bodyId شناسه پترن (اگر نال باشد، پترن پیش‌فرض استفاده می‌شود)
     */
    public function sendDirect(string $to, string $text, array $args = [], ?int $bodyId = null): bool
    {
        if (!$this->isEnabled()) {
            Logger::warning('SmsService', 'پیامک غیرفعال است؛ ارسال مستقیم رد شد', ['to' => $to]);
            return false;
        }

        // تلاش اول: SOAP (روش کلاسیک پنل)
        $soapResult = $this->sendViaSoap($to, $text, $args, $bodyId);
        if ($soapResult === true) {
            return true;
        }

        // تلاش دوم: وب‌سرویس REST ملی‌پیامک (وقتی SOAP در دسترس نیست یا خطا داد)
        $restResult = $this->sendViaRest($to, $text, $args, $bodyId);
        if ($restResult === true) {
            Logger::info('SmsService', 'پیامک از مسیر REST ارسال شد', ['to' => $to]);
            return true;
        }

        Logger::error('SmsService', 'پیامک از هیچ‌یک از مسیرهای SOAP/REST ارسال نشد', ['to' => $to]);
        return false;
    }

    /** ارسال با SOAP — نتیجه: true موفق | false ناموفق | نال یعنی اصلاً قابل اجرا نبود */
    private function sendViaSoap(string $to, string $text, array $args, ?int $bodyId): ?bool
    {
        if (!class_exists(\SoapClient::class)) {
            Logger::warning('SmsService', 'افزونهٔ soap نصب نیست؛ تلاش از مسیر REST');
            return null;
        }
        try {
            ini_set('soap.wsdl_cache_enabled', '0');
            /** @var \SoapClient $sms */
            $sms = new \SoapClient(
                'https://api.payamak-panel.com/post/Send.asmx?wsdl',
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
            $this->lastDetail = 'کد برگشتی پنل: ' . (is_scalar($result) ? (string) $result : gettype($result));
            Logger::error('SmsService', 'ارسال پیامک با SOAP ناموفق بود', [
                'to' => $to,
                'provider_result' => is_scalar($result) ? (string) $result : gettype($result),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->lastDetail = 'خطای ارتباط: ' . $e->getMessage();
            Logger::error('SmsService', 'خطا در ارتباط SOAP با سامانهٔ پیامک', ['to' => $to], $e);
            return null; // خطای ارتباطی → مسیر جایگزین امتحان شود
        }
    }

    /**
     * ارسال آزمایشی برای عیب‌یابی — جزئیات هر دو مسیر SOAP و REST را برمی‌گرداند.
     *
     * @return array{enabled:bool, soap_available:bool, curl_available:bool, sent:bool, soap:string, rest:string}
     */
    public function diagnose(string $to): array
    {
        $to = \App\Utilities\PhoneHelper::normalize($to);
        $report = [
            'enabled' => $this->isEnabled(),
            'soap_available' => class_exists(\SoapClient::class),
            'curl_available' => function_exists('curl_init'),
            'sent' => false,
            'soap' => '',
            'rest' => '',
        ];
        if (!$report['enabled']) {
            $report['soap'] = $report['rest'] = 'غیرفعال — متغیرهای MELIPAYAMAK_USERNAME / MELIPAYAMAK_PASSWORD / MELIPAYAMAK_BODY_ID در فایل .env تنظیم نشده‌اند.';
            return $report;
        }
        if (!\App\Utilities\PhoneHelper::isValid($to)) {
            $report['soap'] = $report['rest'] = 'شماره مقصد معتبر نیست.';
            return $report;
        }

        $text = 'پیامک آزمایشی سامانهٔ مدیریت ساختمان — ' . date('Y/m/d H:i:s');

        $soap = $this->sendViaSoap($to, $text, [], null);
        $report['soap'] = $soap === null
            ? 'در دسترس نبود: ' . $this->lastDetail
            : ($soap ? 'ارسال موفق ✅' : 'خطا: ' . $this->lastDetail);
        if ($soap === true) {
            $report['sent'] = true;
            $report['rest'] = 'نیازی به مسیر جایگزین نبود.';
            return $report;
        }

        $rest = $this->sendViaRest($to, $text, [], null);
        $report['rest'] = $rest ? 'ارسال موفق ✅' : 'خطا: ' . $this->lastDetail;
        $report['sent'] = $rest;
        return $report;
    }

    /** ارسال با وب‌سرویس REST ملی‌پیامک (بدون نیاز به افزونهٔ SOAP) */
    private function sendViaRest(string $to, string $text, array $args, ?int $bodyId): bool
    {
        if (!function_exists('curl_init')) {
            Logger::warning('SmsService', 'افزونهٔ curl هم در دسترس نیست؛ پیامک ارسال نشد', ['to' => $to]);
            return false;
        }
        $payload = [
            'username' => $this->username,
            'password' => $this->password,
            'to' => $to,
            'bodyId' => $bodyId ?? $this->bodyId,
            // در ارسال پترن، متن همان آرگومان‌های پترن است
            'text' => !empty($args) ? array_values($args) : [$text],
        ];
        $ch = curl_init('https://rest.payamak-panel.com/api/SendSMS/SendByBaseNumber');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            $this->lastDetail = 'خطای ارتباط (http=' . $httpCode . ($curlError !== '' ? ', ' . $curlError : '') . ')';
            Logger::error('SmsService', 'خطای ارتباط REST با سامانهٔ پیامک', [
                'to' => $to, 'http_code' => $httpCode, 'curl_error' => $curlError,
            ]);
            return false;
        }
        $json = json_decode((string) $body, true);
        // RetStatus=1 یعنی پذیرفته شد؛ مقدارهای دیگر کد خطا هستند
        if ((int) ($json['RetStatus'] ?? 0) === 1) {
            return true;
        }
        $this->lastDetail = 'پاسخ پنل: ' . ($json['StrRetStatus'] ?? (string) $body);
        Logger::error('SmsService', 'ارسال پیامک با REST رد شد', [
            'to' => $to,
            'ret_status' => $json['RetStatus'] ?? null,
            'provider_message' => $json['StrRetStatus'] ?? (string) $body,
        ]);
        return false;
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
     * پیامک کد یک‌بارمصرف ورود/ثبت‌نام — ارسال فوری (خارج از صف) تا کاربر معطل نماند.
     */
    public function sendOtpSms(string $to, string $code): bool
    {
        $minutes = (int) ceil(\App\Services\OtpService::TTL_SECONDS / 60);
        $text = "کد ورود شما به سامانه مدیریت ساختمان: {$code}\nاعتبار: {$minutes} دقیقه. این کد را در اختیار کسی قرار ندهید.";
        // پترن اختصاصی کد ورود (در صورت تنظیم) نسبت به پترن عمومی اولویت دارد
        $otpBodyId = (int) \App\Config\AppConfig::env('MELIPAYAMAK_OTP_BODY_ID', '0');
        return $this->sendByBaseNumber($to, $text, [$code], $otpBodyId > 0 ? $otpBodyId : null, true);
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
